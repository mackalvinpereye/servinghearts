import argparse
import numpy as np
import pandas as pd
from sqlalchemy import create_engine, text
from statsmodels.tsa.statespace.sarimax import SARIMAX

# DB (XAMPP defaults)
DB_USER, DB_PASS, DB_HOST, DB_PORT, DB_NAME = "root", "", "127.0.0.1", 3306, "shinventory"
engine = create_engine(
    f"mysql+pymysql://{DB_USER}:{DB_PASS}@{DB_HOST}:{DB_PORT}/{DB_NAME}?charset=utf8mb4",
    pool_pre_ping=True, future=True
)

def load_series(ttype: str) -> pd.Series:
    q = """
    SELECT DATE(created_at) AS day, SUM(quantity) AS qty
    FROM blood_transactionprediction
    WHERE UPPER(TRIM(transaction_type)) = :t
    GROUP BY day ORDER BY day
    """
    df = pd.read_sql(text(q), engine, params={"t": ttype}, parse_dates=["day"])
    if df.empty:
        raise SystemExit(f"No rows for {ttype}.")
    idx = pd.date_range(df["day"].min(), df["day"].max(), freq="D")
    s = df.set_index("day")["qty"].reindex(idx).fillna(0.0).astype(float)
    s.index.name = "day"  # daily frequency
    return s

def predict_range(series: pd.Series, start: pd.Timestamp, end: pd.Timestamp) -> pd.DataFrame:
    try:
        m = SARIMAX(
            series,
            order=(1, 0, 0),
            seasonal_order=(1, 1, 0, 7),
            enforce_stationarity=False,
            enforce_invertibility=False,
        )
        r = m.fit(disp=False)
        p = r.get_prediction(start=start, end=end)
        yhat = p.predicted_mean
        ci = p.conf_int(alpha=0.2)  # 80% band
        lower, upper = ci.iloc[:, 0], ci.iloc[:, 1]
    except Exception:
        # fallback if model fails
        dates = pd.date_range(start, end, freq="D")
        mean = float(series.tail(28).mean()) if len(series) else 0.0
        yhat = pd.Series(mean, index=dates); lower = yhat * 0.8; upper = yhat * 1.2

    df = pd.DataFrame({
        "day": yhat.index.normalize(),
        "yhat": yhat.clip(lower=0).astype(float),
        "yhat_lower": lower.clip(lower=0).astype(float),
        "yhat_upper": upper.clip(lower=0).astype(float),
    })
    return df

def upsert(df: pd.DataFrame, ttype: str, model="sarimax_v1"):
    if df.empty:
        return  # nothing to write
    sql = """
    INSERT INTO forecast_daily (day, transaction_type, yhat, yhat_lower, yhat_upper, model)
    VALUES (:day, :ttype, :yhat, :yhat_lower, :yhat_upper, :model)
    ON DUPLICATE KEY UPDATE
      yhat = VALUES(yhat),
      yhat_lower = VALUES(yhat_lower),
      yhat_upper = VALUES(yhat_upper),
      model = VALUES(model)
    """
    recs = [{
        "day": d.date(),
        "ttype": ttype,
        "yhat": float(a),
        "yhat_lower": float(b),
        "yhat_upper": float(c),
        "model": model
    } for d, a, b, c in zip(df["day"], df["yhat"], df["yhat_lower"], df["yhat_upper"])]
    if not recs:
        return
    with engine.begin() as conn:
        conn.execute(text(sql), recs)

def main():
    ap = argparse.ArgumentParser()
    grp = ap.add_mutually_exclusive_group(required=True)
    grp.add_argument("--month", help="YYYY-MM")
    grp.add_argument("--day", help="YYYY-MM-DD")
    ap.add_argument("--round", dest="round_vals", action="store_true",
                    help="Round yhat and bands to whole units before saving")
    args = ap.parse_args()

    s_in  = load_series("IN")
    s_out = load_series("OUT")

    if args.day:
        start = end = pd.to_datetime(args.day)
    else:
        start = pd.to_datetime(args.month + "-01")
        end = (start + pd.offsets.MonthEnd(1)).normalize()

    pin  = predict_range(s_in, start, end)
    pout = predict_range(s_out, start, end)

    if args.round_vals:
        for df in (pin, pout):
            df["yhat"] = df["yhat"].round().clip(lower=0)
            df["yhat_lower"] = df["yhat_lower"].round().clip(lower=0)
            df["yhat_upper"] = df["yhat_upper"].round().clip(lower=0)

    # Guard: if nothing predicted, exit with clear message (ASCII arrow for PHP shell_exec)
    if pin.empty or pout.empty:
        start_str = pd.to_datetime(start).date()
        end_str   = pd.to_datetime(end).date()
        raise SystemExit(f"No predictions produced for range {start_str} -> {end_str}.")

    upsert(pin,  "IN")
    upsert(pout, "OUT")

    n = len(pin)
    # Safe log using ASCII
    start_str = pd.to_datetime(start).date()
    end_str   = pd.to_datetime(end).date()
    print(f"Wrote {n} IN + {n} OUT rows for range {start_str} -> {end_str} into forecast_daily.")

if __name__ == "__main__":
    main()
