#!/bin/sh
# Generates the differential tests' cases from libsecp256k1 (coincurve, pinned by hash in
# tools/differential/requirements.txt), in a virtual environment of the given Python.
# Usage: sh tools/ci/cases.sh <python> <count> <seed> <output file>
set -eu

python=$1
count=$2
seed=$3
out=$4

venv=$(mktemp -d)
"$python" -m venv "$venv"
"$venv/bin/python" -m pip install --quiet --disable-pip-version-check --require-hashes --no-deps -r tools/differential/requirements.txt
mkdir -p "$(dirname "$out")"
echo "Seed: $seed"
"$venv/bin/python" -I tools/differential/generate.py "$count" "$seed" "$out"
