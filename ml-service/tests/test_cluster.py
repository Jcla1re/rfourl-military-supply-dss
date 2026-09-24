import os
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from app import app  # noqa: E402


@pytest.fixture
def client():
    app.config["TESTING"] = True
    with app.test_client() as c:
        yield c


def fast_slow_items():
    # Two tight groups (fast movers vs. slow movers) so 2 clusters is
    # unambiguous regardless of KMeans' random initialization.
    fast = [
        {"item_id": f"ITM-F{i}", "unit_cost": 100, "avg_daily_demand": 10 + i * 0.1,
         "demand_std_dev": 2, "lead_time_days": 7}
        for i in range(3)
    ]
    slow = [
        {"item_id": f"ITM-S{i}", "unit_cost": 100, "avg_daily_demand": 0.1 + i * 0.01,
         "demand_std_dev": 0.05, "lead_time_days": 7}
        for i in range(3)
    ]
    return fast + slow


def three_tier_items():
    # Three tight, well-separated groups so K=3 clustering is unambiguous
    # regardless of KMeans' random initialization.
    fast = [
        {"item_id": f"ITM-F{i}", "unit_cost": 100, "avg_daily_demand": 20 + i * 0.1,
         "demand_std_dev": 4, "lead_time_days": 5}
        for i in range(3)
    ]
    medium = [
        {"item_id": f"ITM-M{i}", "unit_cost": 100, "avg_daily_demand": 5 + i * 0.1,
         "demand_std_dev": 1, "lead_time_days": 7}
        for i in range(3)
    ]
    slow = [
        {"item_id": f"ITM-S{i}", "unit_cost": 100, "avg_daily_demand": 0.1 + i * 0.01,
         "demand_std_dev": 0.05, "lead_time_days": 10}
        for i in range(3)
    ]
    return fast + medium + slow


def test_health(client):
    resp = client.get("/health")
    assert resp.status_code == 200
    assert resp.get_json()["status"] == "ok"


def test_cluster_rejects_empty_items(client):
    resp = client.post("/cluster", json={"items": []})
    assert resp.status_code == 400


def test_cluster_rejects_missing_features(client):
    resp = client.post("/cluster", json={"items": [{"item_id": "ITM-0001", "unit_cost": 1}]})
    assert resp.status_code == 400


def test_cluster_rejects_fewer_items_than_clusters(client):
    resp = client.post("/cluster", json={"items": fast_slow_items()[:2], "n_clusters": 3})
    assert resp.status_code == 400


def test_cluster_separates_fast_and_slow_movers(client):
    resp = client.post("/cluster", json={"items": fast_slow_items(), "n_clusters": 2})
    assert resp.status_code == 200

    body = resp.get_json()
    clusters = {c["item_id"]: c for c in body["clusters"]}

    fast_names = {clusters[f"ITM-F{i}"]["cluster_name"] for i in range(3)}
    slow_names = {clusters[f"ITM-S{i}"]["cluster_name"] for i in range(3)}

    assert len(fast_names) == 1
    assert len(slow_names) == 1
    assert fast_names != slow_names
    assert len(body["centers"]) == 2


def test_cluster_assigns_abc_class_and_z_score_for_three_tiers(client):
    resp = client.post("/cluster", json={"items": three_tier_items(), "n_clusters": 3})
    assert resp.status_code == 200

    clusters = {c["item_id"]: c for c in resp.get_json()["clusters"]}

    # Highest-demand tier -> Class A / 95% service level / Z=1.645, and so on.
    for i in range(3):
        assert clusters[f"ITM-F{i}"]["abc_class"] == "A"
        assert clusters[f"ITM-F{i}"]["service_level"] == 0.95
        assert clusters[f"ITM-F{i}"]["z_score"] == 1.645

        assert clusters[f"ITM-M{i}"]["abc_class"] == "B"
        assert clusters[f"ITM-M{i}"]["service_level"] == 0.90
        assert clusters[f"ITM-M{i}"]["z_score"] == 1.282

        assert clusters[f"ITM-S{i}"]["abc_class"] == "C"
        assert clusters[f"ITM-S{i}"]["service_level"] == 0.85
        assert clusters[f"ITM-S{i}"]["z_score"] == 1.036


def test_cluster_leaves_abc_class_null_when_not_three_clusters(client):
    resp = client.post("/cluster", json={"items": fast_slow_items(), "n_clusters": 2})
    assert resp.status_code == 200

    for c in resp.get_json()["clusters"]:
        assert c["abc_class"] is None
        assert c["service_level"] is None
        assert c["z_score"] is None
