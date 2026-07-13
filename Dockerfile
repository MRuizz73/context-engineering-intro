FROM python:3.11-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY gestion_flota ./gestion_flota

# La base SQLite vive en /data (montar un volumen para que persista).
ENV DATABASE_URL=sqlite:////data/gestion_flota.db
RUN mkdir -p /data
VOLUME /data

EXPOSE 8000
CMD ["uvicorn", "gestion_flota.main:app", "--host", "0.0.0.0", "--port", "8000"]
