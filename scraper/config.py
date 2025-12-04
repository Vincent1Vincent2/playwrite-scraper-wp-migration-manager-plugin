"""Configuration settings for the scraper API."""

import os
from typing import List, Optional

# Browser settings
BROWSER_HEADLESS: bool = True
BROWSER_ARGS: List[str] = ['--no-sandbox', '--disable-dev-shm-usage']

# API settings
API_HOST: str = os.getenv("API_HOST", "0.0.0.0")
API_PORT: int = int(os.getenv("API_PORT", "8000"))

# Logging
LOG_LEVEL: str = os.getenv("LOG_LEVEL", "INFO")

