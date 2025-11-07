FROM python:3.11-slim

WORKDIR /app

# Copy requirements and install dependencies
COPY requirments.txt .
RUN pip install --no-cache-dir -r requirments.txt && \
    pip install --no-cache-dir aiomysql

# Copy the rest of the application
COPY . .

# Run the bot
CMD ["python", "-u", "main.py"]
