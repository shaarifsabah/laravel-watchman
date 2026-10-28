#!/usr/bin/env bash
# Builds ./parity with the Go toolchain on PATH (Go >= 1.26), using only modules already available locally.
set -euo pipefail
cd "$(dirname "$0")"
export GOTOOLCHAIN=local GOFLAGS=-mod=mod GOPROXY=off GOSUMDB=off GONOSUMDB='*'
go build -trimpath -o parity .
