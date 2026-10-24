#!/bin/bash
# Restart the server
docker-compose down
docker-compose up -d --build
