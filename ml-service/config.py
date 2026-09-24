"""Configuration constants for the K-Means clustering microservice."""

K = 3

FEATURES = [
    "unit_cost",
    "avg_daily_demand",
    "demand_std_dev",
    "lead_time_days",
]

RANDOM_STATE = 42
N_INIT = 10

# Standard service-level -> Z-score lookup, kept here since the DSS
# parameters (safety stock, reorder point) are computed alongside the
# same feature set consumed by this service.
Z_SCORE_MAP = {
    0.85: 1.036,
    0.90: 1.282,
    0.95: 1.645,
    0.975: 1.960,
    0.99: 2.326,
}

# Human-readable names for the 3 clusters, assigned by ranking each
# cluster's centroid avg_daily_demand from highest to lowest. Only used
# when n_clusters matches this list's length; otherwise clusters are
# named generically ("Cluster 0", "Cluster 1", ...).
CLUSTER_NAMES_BY_RANK = ["Fast-Moving", "Medium-Moving", "Slow-Moving"]

# Multi-criteria ABC policy tier per cluster rank (rank 0 = the cluster
# with the highest avg_daily_demand centroid). This is what lets K-Means
# output drive the ABC class actually used for procurement, instead of
# only producing a descriptive label. Only applied when n_clusters == 3.
ABC_CLASS_BY_RANK = ["A", "B", "C"]

# Service level target per ABC class, per the capstone's classification
# scheme (Class A/B/C -> 95%/90%/85%), converted to a Z-score via
# Z_SCORE_MAP so the CI4 DSS engine can use it directly in Safety Stock
# and Reorder Point without duplicating this table.
SERVICE_LEVEL_BY_CLASS = {
    "A": 0.95,
    "B": 0.90,
    "C": 0.85,
}
