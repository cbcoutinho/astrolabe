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

# Long enough (well over the MCP server's 2048-character chunks) to index as
# several passages that all match a Kubernetes query, so search.spec.ts can
# tell "one row per document" from "best passages".
cat > "$NOTES_DIR/Kubernetes Cluster Operations Runbook.md" << 'NOTEEOF'
# Kubernetes Cluster Operations Runbook

## Upgrading the Kubernetes cluster
Upgrade the Kubernetes cluster one minor version at a time. Drain each worker
node with kubectl drain, upgrade the kubelet and container runtime, then
uncordon the node and wait until every pod on it is ready before moving to the
next node. Upgrade the control plane nodes first, one at a time, and confirm
the API server, scheduler and controller manager are healthy after each one.
Keep etcd snapshots from before the upgrade so the cluster can be restored if
the control plane fails to come back. Read the release notes for removed API
versions and migrate manifests before upgrading, because workloads that use a
removed API version stop deploying after the upgrade. Schedule upgrades in a
maintenance window and announce them to the teams that run workloads on the
cluster, since draining nodes restarts their pods.

## Scaling Kubernetes cluster nodes
The cluster autoscaler adds worker nodes when pods are pending for lack of CPU
or memory and removes nodes that stay underused. Set resource requests on every
workload so the scheduler and the autoscaler can make good decisions; pods
without requests are packed onto nodes until they run out of memory. Use pod
disruption budgets so scale-down never evicts every replica of a service at
once. Spread replicas across availability zones with topology spread
constraints, and keep a small buffer of spare capacity so a node failure does
not leave critical pods pending while a replacement node boots. Review node
pool sizes every quarter against actual usage, and right-size instance types
when nodes are consistently underused.

## Kubernetes cluster networking
The cluster uses a CNI plugin for pod networking and network policies to limit
which pods may talk to each other. Every namespace starts with a default-deny
ingress policy, and services open only the ports they need. Ingress
controllers terminate TLS at the edge with certificates issued automatically by
cert-manager. When pods cannot reach a service, check the service selector
matches the pod labels, that the endpoints object lists ready pods, and that no
network policy blocks the traffic. DNS problems inside the cluster usually come
from an overloaded CoreDNS deployment, so scale it with the number of nodes and
watch its error rate. Keep the pod and service CIDR ranges documented so they
never overlap with office or VPN networks.

## Kubernetes cluster storage
Stateful workloads use persistent volume claims backed by the cluster's CSI
driver. Choose a storage class with the right reclaim policy: Retain for
databases, so deleting a claim never deletes the data, and Delete for scratch
space. Back up persistent volumes with scheduled snapshots and test restoring
them into a separate namespace every month. When a volume will not attach,
check whether it is still attached to a node that went away; the CSI driver
detaches it after a timeout, or an operator can force the detachment. Watch
volume usage and expand claims before they fill up, since a full volume stops
the database that uses it.

## Kubernetes cluster incident response
When the cluster is degraded, start with the control plane: check that the API
server answers, that etcd has quorum, and that nodes report Ready. Then look at
recent events for failed scheduling, image pull errors and crash loops. Roll
back a bad deployment with kubectl rollout undo rather than editing it in
place. Record every incident with a timeline, the impact, and the follow-up
actions, and review them at the weekly operations meeting so the same failure
does not happen twice.
NOTEEOF

# Scan files so Nextcloud knows about them
php /var/www/html/occ files:scan admin

echo "Test data seeded successfully"
