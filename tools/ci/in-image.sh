#!/bin/sh
# Runs a CI job's commands in a fresh container of an image, as root, on a copy of the working
# directory sent through Docker's API (docker cp), then removes the container. A runner needs a
# Docker daemon alone, its own or not: nothing of the runner's disk is mounted in the container (a
# runner that runs in a container has no path the daemon could mount), and nothing the container
# writes comes back to it (no file left that the runner's user could not delete).
# Usage: sh tools/ci/in-image.sh <image> <commands>; the commands run under sh -e, in /app.
set -eu

image=$1
commands=$2

id=$(docker create --workdir /app --env COMPOSER_ALLOW_SUPERUSER=1 "$image" sh -ec "$commands")
trap 'docker rm --force "$id" >/dev/null' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker cp ./. "$id:/app"
# In the background, so that a signal (the job cancelled, or out of time) is handled at once: the
# container is removed, its commands with it.
docker start --attach "$id" &
wait $!
