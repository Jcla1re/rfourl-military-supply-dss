import os
import sys

import numpy as np
import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from preprocessing import normalize_features, to_feature_frame, to_original_units  # noqa: E402


def sample_items():
    return [
        {"item_id": "ITM-0001", "unit_cost": 100.0, "avg_daily_demand": 1.0, "demand_std_dev": 0.5, "lead_time_days": 7},
        {"item_id": "ITM-0002", "unit_cost": 200.0, "avg_daily_demand": 2.0, "demand_std_dev": 1.0, "lead_time_days": 14},
        {"item_id": "ITM-0003", "unit_cost": 300.0, "avg_daily_demand": 3.0, "demand_std_dev": 1.5, "lead_time_days": 21},
    ]


def test_to_feature_frame_selects_and_orders_columns():
    frame = to_feature_frame(sample_items())
    # unit_cost and lead_time_days are sent by the backend but are not clustering features
    assert list(frame.columns) == ["avg_daily_demand", "demand_std_dev"]
    assert frame.shape == (3, 2)


def test_normalize_features_scales_to_unit_range():
    frame = to_feature_frame(sample_items())
    scaled, scaler = normalize_features(frame)

    assert scaled.min() == 0.0
    assert scaled.max() == 1.0
    assert scaled.shape == (3, 2)

    restored = to_original_units(scaler, scaled)
    assert restored[0][0] == pytest.approx(1.0)
    assert restored[2][0] == pytest.approx(3.0)
    assert restored[2][1] == pytest.approx(1.5)


def test_demand_features_are_log1p_transformed():
    items = [
        {"item_id": "a", "unit_cost": 0.0, "avg_daily_demand": 0.0, "demand_std_dev": 0.0, "lead_time_days": 7},
        {"item_id": "b", "unit_cost": 50.0, "avg_daily_demand": 1.0, "demand_std_dev": 1.0, "lead_time_days": 7},
        {"item_id": "c", "unit_cost": 100.0, "avg_daily_demand": 9.0, "demand_std_dev": 9.0, "lead_time_days": 7},
    ]
    scaled, _ = normalize_features(to_feature_frame(items))

    expected = np.log1p(1.0) / np.log1p(9.0)
    assert scaled[1][0] == pytest.approx(expected)
    assert scaled[1][1] == pytest.approx(expected)
    assert expected > 0.1  # a linear scale would put it at 0.1: the skew is reduced
