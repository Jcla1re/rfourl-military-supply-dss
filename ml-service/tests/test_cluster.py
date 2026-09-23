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
