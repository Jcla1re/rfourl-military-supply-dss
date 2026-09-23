import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from preprocessing import normalize_features, to_feature_frame  # noqa: E402


def sample_items():
    return [
        {"item_id": "ITM-0001", "unit_cost": 100.0, "avg_daily_demand": 1.0, "demand_std_dev": 0.5, "lead_time_days": 7},
        {"item_id": "ITM-0002", "unit_cost": 200.0, "avg_daily_demand": 2.0, "demand_std_dev": 1.0, "lead_time_days": 14},
        {"item_id": "ITM-0003", "unit_cost": 300.0, "avg_daily_demand": 3.0, "demand_std_dev": 1.5, "lead_time_days": 21},
    ]


def test_to_feature_frame_selects_and_orders_columns():
    frame = to_feature_frame(sample_items())
    assert list(frame.columns) == ["unit_cost", "avg_daily_demand", "demand_std_dev", "lead_time_days"]
    assert frame.shape == (3, 4)


def test_normalize_features_scales_to_unit_range():
    frame = to_feature_frame(sample_items())
    scaled, scaler = normalize_features(frame)

    assert scaled.min() == 0.0
    assert scaled.max() == 1.0
    assert scaled.shape == (3, 4)

    restored = scaler.inverse_transform(scaled)
    assert restored[0][0] == 100.0
    assert restored[2][0] == 300.0
