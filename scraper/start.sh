#!/bin/bash

# Start the FastAPI application with uvicorn
exec uvicorn app:app --host 0.0.0.0 --port 8000 --reload