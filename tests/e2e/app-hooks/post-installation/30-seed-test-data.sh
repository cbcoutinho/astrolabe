#!/bin/bash
# Seed test data for E2E tests
#
# Creates sample notes and deck cards as the admin user so that the MCP server
# has content to index for semantic search and Plotly visualization tests.
set -euox pipefail

echo "Seeding test data for E2E tests..."

# Create test notes as files in the admin user's Notes directory
# The Notes app reads .txt/.md files from the user's Notes folder
NOTES_DIR="/var/www/html/data/admin/files/Notes"
mkdir -p "$NOTES_DIR"

cat > "$NOTES_DIR/Kubernetes Cluster Architecture.md" << 'NOTEEOF'
# Kubernetes Cluster Architecture

This note describes the homelab Kubernetes cluster architecture.

## Components
- Control plane running on 3 nodes with etcd
- Worker nodes with mixed workloads
- Ingress controller using Traefik
- Storage provided by Longhorn distributed block storage
- Monitoring stack with Prometheus and Grafana

## Networking
Pod networking uses Cilium CNI with eBPF datapath.
Service mesh is not currently deployed but planned for future.
NOTEEOF

cat > "$NOTES_DIR/Nextcloud Deployment Guide.md" << 'NOTEEOF'
# Nextcloud Deployment Guide

Steps to deploy Nextcloud on the homelab cluster.

## Prerequisites
- Helm chart repository added
- PostgreSQL database provisioned
- Redis cache configured
- S3-compatible storage for primary storage

## Configuration
The Nextcloud deployment uses a custom values file that configures:
- PHP-FPM with optimized pool settings
- Redis for file locking and session storage
- SMTP relay for outbound email notifications
- OIDC authentication via Keycloak
NOTEEOF

cat > "$NOTES_DIR/Semantic Search Research.md" << 'NOTEEOF'
# Semantic Search Research

Notes on implementing semantic search for personal knowledge management.

## Embedding Models
- nomic-embed-text provides good quality for general text
- Sentence transformers offer multilingual support
- OpenAI text-embedding-3-small is fast and cost-effective

## Vector Databases
- Qdrant offers excellent filtering and payload support
- ChromaDB is simpler but less feature-rich
- Both support cosine similarity and dot product metrics

## Chunking Strategies
- Fixed-size chunks with overlap work well for general documents
- Semantic chunking preserves paragraph boundaries
- Recursive character splitting is a good default approach
NOTEEOF

# Scan files so Nextcloud knows about them
php /var/www/html/occ files:scan admin

echo "Test data seeded successfully"
