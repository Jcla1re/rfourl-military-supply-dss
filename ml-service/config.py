"""Configuration constants for the K-Means clustering microservice."""

K = 3

# The 2 features K-Means clusters on: how fast an item sells and how much that
# varies. Both are right-skewed (most items sell well below 1/day, a few sell
# much more), so log1p is applied before min-max scaling.
#
# Not clustered on, on purpose:
#  - lead_time_days: set per supplier and shared by a whole category, so it
#    would group items by category instead of by sales.
#  - unit_cost: it split the classes by price (the "lowest demand" class
#    became the expensive items) instead of by demand.
# Lead time is still used for Safety Stock / ROP.
FEATURES = [
    "avg_daily_demand",
    "demand_std_dev",
]

# Features that get log1p before min-max scaling (all of them).
LOG_FEATURES = list(FEATURES)

# Fields every item in the request must carry. The CI4 side keeps sending
# unit_cost and lead_time_days (API contract unchanged), so they are still
# validated, but they are not used for clustering.
REQUIRED_FIELDS = FEATURES + ["unit_cost", "lead_time_days"]

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
