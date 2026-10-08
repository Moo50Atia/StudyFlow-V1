FROM ubuntu:noble

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends \
    python3 \
    python3-pip \
    libglib2.0-0t64 \
    libnss3 \
    libnspr4 \
    libatk1.0-0t64 \
    libatk-bridge2.0-0t64 \
    libcups2t64 \
    libdrm2 \
    libxkbcommon0 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxrandr2 \
    libgbm1 \
    libpango-1.0-0 \
    libcairo2 \
    libasound2t64 \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /wheels
COPY v3_engine/qualification/wheels/*.whl /wheels/
RUN pip3 install --no-cache-dir --break-system-packages /wheels/*.whl && rm -rf /wheels

COPY v3_engine/qualification/chrome_dist /opt/google/chrome

RUN useradd -m -u 10001 -s /bin/bash pwuser

WORKDIR /app
COPY v3_engine/qualification/sandbox_entry.py /app/v3_engine/qualification/sandbox_entry.py
COPY v3_engine/qualification/browser_checks.py /app/v3_engine/qualification/browser_checks.py
COPY v3_engine/qualification/__init__.py /app/v3_engine/qualification/__init__.py
COPY v3_engine/review /app/v3_engine/review
COPY resources/v3/scene/check-codes-v1.json /app/resources/v3/scene/check-codes-v1.json

ENV PYTHONPATH=/app

USER 10001:10001

ENTRYPOINT ["python3", "/app/v3_engine/qualification/sandbox_entry.py"]
