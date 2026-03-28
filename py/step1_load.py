import pandas as pd
from sqlalchemy import create_engine

# XAMPP defaults — change password if you set one; change DB_NAME if different
DB_USER, DB_PASS, DB_HOST, DB_PORT, DB_NAME = "root", "", "127.0.0.1", 3306, "shinventory"

engine = create_engine(
    f"mysql+pymysql://{DB_USER}:{DB_PASS}@{DB_HOST}:{DB_PORT}/{DB_NAME}",
    pool_pre_ping=True, future=True
)

# --- Diagnostics from blood_transactionprediction ONLY ---
print("=== Diagnostics (blood_transactionprediction only) ===")
diag_sql = """
SELECT
  COALESCE(transaction_type,'<NULL>') AS raw_type,
  UPPER(TRIM(COALESCE(transaction_type,''))) AS normalized_type,
  COUNT(*) AS n_rows,
  SUM(quantity) AS total_qty,
  MIN(created_at) AS first_ts,
  MAX(created_at) AS last_ts
FROM blood_transactionprediction
GROUP BY raw_type, normalized_type
ORDER BY n_rows DESC;
"""
diag = pd.read_sql(diag_sql, engine)
print(diag.to_string(index=False))
print()

# --- Daily IN/OUT from blood_transactionprediction ONLY ---
sql = """
SELECT
  DATE(created_at) AS day,
  UPPER(TRIM(transaction_type)) AS transaction_type,
  SUM(quantity) AS qty
FROM blood_transactionprediction
WHERE transaction_type IS NOT NULL AND created_at IS NOT NULL
GROUP BY day, transaction_type
ORDER BY day;
"""
df = pd.read_sql(sql, engine, parse_dates=["day"])

if df.empty:
    raise SystemExit("No rows found in blood_transactionprediction.")

# Pivot to IN/OUT and make the index a continuous day range
pivot = df.pivot(index="day", columns="transaction_type", values="qty").sort_index()

for col in ("IN", "OUT"):
    if col not in pivot.columns:
        pivot[col] = 0.0

full_idx = pd.date_range(df["day"].min(), df["day"].max(), freq="D")
pivot = pivot.reindex(full_idx).fillna(0.0)
pivot.index.name = "day"

in_daily  = pivot["IN"].astype(float)
out_daily = pivot["OUT"].astype(float)

print("=== IN head ===")
print(in_daily.head())
print("\n=== OUT head ===")
print(out_daily.head())
print("\nRange:", in_daily.index.min(), "→", in_daily.index.max(),
      "| Lengths:", len(in_daily), len(out_daily))
