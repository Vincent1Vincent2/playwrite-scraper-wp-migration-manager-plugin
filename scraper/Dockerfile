FROM python:3.11-slim

# Install system dependencies (this layer will be cached)
RUN apt-get update && apt-get install -y \
    curl \
    wget \
    gnupg \
    unzip \
    fonts-liberation \
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
    && rm -rf /var/lib/apt/lists/*

# Set workdir
WORKDIR /app

# Copy only requirements first (for better layer caching)
COPY requirements.txt .

# Install Python dependencies (this layer will be cached unless requirements.txt changes)
RUN pip install --no-cache-dir -r requirements.txt

# Install Playwright and browser binaries (this layer will be cached)
RUN playwright install --with-deps

# Copy application code last (this layer will change most frequently)
COPY app.py .
COPY start.sh .

# Make start script executable
RUN chmod +x start.sh

# Expose port
EXPOSE 8000

# Run the app
CMD ["bash", "start.sh"]