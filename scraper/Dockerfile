FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends \
    curl \
    wget \
    gnupg \
    unzip \
    fonts-liberation \
    fonts-unifont \
    libnss3 \
    libatk-bridge2.0-0 \
    libgtk-3-0 \
    libxss1 \
    libasound2 \
    libxshmfence1 \
    libgbm1 \
    libxrandr2 \
    libu2f-udev \
    libvulkan1 \
    libgdk-pixbuf-xlib-2.0-0 \
    libjpeg62-turbo \
    libwebp7 \
    libcups2 \
    && rm -rf /var/lib/apt/lists/*


WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Install only the browser binaries (NO --with-deps)
RUN playwright install chromium

COPY app.py .
COPY start.sh .

RUN chmod +x start.sh

EXPOSE 8000
CMD ["bash", "start.sh"]
