"""
Flask K-Means service cases from the Software Testing Plan (Section 4.4):
UT-12, UT-19 to UT-23, ST-12 and ST-19 (service-side part).

Run:  cd ml-service && python -m pytest tests -v
"""
import os
import sys
import time

import numpy as np
import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from app import app  # noqa: E402
from config import FEATURES, SERVICE_LEVEL_BY_CLASS, Z_SCORE_MAP  # noqa: E402
from preprocessing import normalize_features, to_feature_frame  # noqa: E402


@pytest.fixture
def client():
    app.config["TESTING"] = True
    with app.test_client() as c:
        yield c


def make_items(n=100, seed=7):
    """n products drawn from three loose demand tiers (fast / medium / slow)."""
    rng = np.random.default_rng(seed)
    items = []
    for i in range(n):
        tier = i % 3
        demand = [20.0, 5.0, 0.5][tier] + rng.normal(0, 0.2)
        items.append({
            "item_id": f"ITM-{i:04d}",
            "unit_cost": float([900, 400, 120][tier] + rng.normal(0, 10)),
            "avg_daily_demand": float(max(demand, 0.01)),
            "demand_std_dev": float([4.0, 1.5, 0.3][tier] + abs(rng.normal(0, 0.1))),
            "lead_time_days": int([5, 7, 10][tier]),
        })
    return items


def post_cluster(client, items, n_clusters=3):
    return client.post("/cluster", json={"items": items, "n_clusters": n_clusters})


# --- UT-12 ---------------------------------------------------------------

def test_ut12_service_level_z_scores_per_class():
    assert Z_SCORE_MAP[SERVICE_LEVEL_BY_CLASS["A"]] == pytest.approx(1.645)
    assert Z_SCORE_MAP[SERVICE_LEVEL_BY_CLASS["B"]] == pytest.approx(1.282)
    assert Z_SCORE_MAP[SERVICE_LEVEL_BY_CLASS["C"]] == pytest.approx(1.036)


# --- UT-19 ---------------------------------------------------------------

def test_ut19_min_max_normalization_midpoint_is_half():
    # demand 0, 2, 8 -> log1p = 0, ln 3, 2 ln 3 -> the middle item scales to exactly 0.5
    items = [
        {"item_id": "a", "unit_cost": 10, "avg_daily_demand": 0, "demand_std_dev": 0, "lead_time_days": 5},
        {"item_id": "b", "unit_cost": 50, "avg_daily_demand": 2, "demand_std_dev": 2, "lead_time_days": 5},
        {"item_id": "c", "unit_cost": 90, "avg_daily_demand": 8, "demand_std_dev": 8, "lead_time_days": 5},
    ]
    scaled, _ = normalize_features(to_feature_frame(items))

    assert scaled[1][FEATURES.index("avg_daily_demand")] == pytest.approx(0.5)
    assert scaled[1][FEATURES.index("demand_std_dev")] == pytest.approx(0.5)


def test_ut19_identical_values_normalize_to_zero_without_error():
    # demand_std_dev identical for every product -> that column scales to 0, no NaN / divide-by-zero
    items = [
        {"item_id": str(i), "unit_cost": 100 + i, "avg_daily_demand": 1 + i,
         "demand_std_dev": 2, "lead_time_days": 7}
        for i in range(3)
    ]
    scaled, _ = normalize_features(to_feature_frame(items))
    std = scaled[:, FEATURES.index("demand_std_dev")]

    assert np.all(std == 0)
    assert not np.isnan(scaled).any()


def test_ut19_only_demand_features_are_used_for_clustering():
    assert FEATURES == ["avg_daily_demand", "demand_std_dev"]
    assert "lead_time_days" not in FEATURES
    assert "unit_cost" not in FEATURES


def test_ut19_demand_features_use_log1p_before_scaling():
    items = [
        {"item_id": "a", "unit_cost": 10, "avg_daily_demand": 0, "demand_std_dev": 0, "lead_time_days": 5},
        {"item_id": "b", "unit_cost": 50, "avg_daily_demand": 1, "demand_std_dev": 1, "lead_time_days": 5},
        {"item_id": "c", "unit_cost": 90, "avg_daily_demand": 9, "demand_std_dev": 9, "lead_time_days": 5},
    ]
    scaled, _ = normalize_features(to_feature_frame(items))

    assert scaled[1][FEATURES.index("avg_daily_demand")] == pytest.approx(np.log1p(1) / np.log1p(9))


def test_lead_time_does_not_change_the_clusters(client):
    items = make_items(60)
    base = post_cluster(client, items).get_json()
    for i, item in enumerate(items):
        item["lead_time_days"] = 3 + (i * 7) % 20
    changed = post_cluster(client, items).get_json()

    assert [c["abc_class"] for c in base["clusters"]] == [c["abc_class"] for c in changed["clusters"]]


def test_unit_cost_does_not_change_the_clusters(client):
    items = make_items(60)
    base = post_cluster(client, items).get_json()
    for i, item in enumerate(items):
        item["unit_cost"] = 5 + (i * 937) % 3000
    changed = post_cluster(client, items).get_json()

    assert [c["abc_class"] for c in base["clusters"]] == [c["abc_class"] for c in changed["clusters"]]


def test_centers_are_in_original_units_with_only_the_clustering_features(client):
    body = post_cluster(client, make_items(60)).get_json()

    for center in body["centers"]:
        assert set(center) == set(FEATURES)
    a = [c for c in body["clusters"] if c["abc_class"] == "A"][0]
    assert 10 < body["centers"][a["cluster_label"]]["avg_daily_demand"] < 30  # tier A is about 20 a day


# --- UT-20 ---------------------------------------------------------------

def test_ut20_every_product_gets_exactly_one_of_three_labels(client):
    items = make_items(100)
    resp = post_cluster(client, items)
    body = resp.get_json()

    assert resp.status_code == 200
    assert len(body["clusters"]) == 100
    assert {c["item_id"] for c in body["clusters"]} == {i["item_id"] for i in items}
    assert {c["cluster_label"] for c in body["clusters"]} <= {0, 1, 2}
    assert {c["abc_class"] for c in body["clusters"]} <= {"A", "B", "C"}


# --- UT-21 ---------------------------------------------------------------

def test_ut21_same_input_gives_identical_labels(client):
    items = make_items(100)
    first = post_cluster(client, items).get_json()["clusters"]
    second = post_cluster(client, items).get_json()["clusters"]

    assert [c["cluster_label"] for c in first] == [c["cluster_label"] for c in second]


# --- UT-22 ---------------------------------------------------------------

def test_ut22_highest_demand_cluster_is_class_a_lowest_is_class_c(client):
    body = post_cluster(client, make_items(60)).get_json()
    centers_by_class = {}
    for c in body["clusters"]:
        centers_by_class[c["abc_class"]] = body["centers"][c["cluster_label"]]["avg_daily_demand"]

    assert centers_by_class["A"] > centers_by_class["B"] > centers_by_class["C"]


def test_ut22_class_z_score_follows_service_level(client):
    clusters = post_cluster(client, make_items(60)).get_json()["clusters"]
    z_by_class = {c["abc_class"]: c["z_score"] for c in clusters}

    assert z_by_class == {"A": 1.645, "B": 1.282, "C": 1.036}


# --- UT-23 ---------------------------------------------------------------

def test_ut23_missing_lead_time_is_http_400(client):
    items = make_items(10)
    for item in items:
        del item["lead_time_days"]
    resp = post_cluster(client, items)

    assert resp.status_code == 400
    assert "lead_time_days" in resp.get_json()["error"]


def test_ut23_only_two_products_is_http_400(client):
    resp = post_cluster(client, make_items(2))

    assert resp.status_code == 400
    assert "error" in resp.get_json()


def test_ut23_empty_payload_is_http_400(client):
    assert client.post("/cluster", json={}).status_code == 400
    assert client.post("/cluster", data="not json").status_code == 400


# --- ST-12 (service side) ---------------------------------------------------

def test_st12_100_products_cluster_in_under_10_seconds(client):
    items = make_items(100)
    durations = []
    for _ in range(10):
        start = time.perf_counter()
        resp = post_cluster(client, items)
        durations.append(time.perf_counter() - start)
        assert resp.status_code == 200

    print(f"ST-12 clustering times (s): {[round(d, 3) for d in durations]}, max {max(durations):.3f}")
    assert max(durations) <= 10.0
