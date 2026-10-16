#!/bin/sh
# Copies the protocol's JSON Schema from an Eclair checkout, and records its commit.
#   tests/protocol/update.sh ../eclair
set -eu
eclair=${1:?usage: tests/protocol/update.sh <eclair checkout>}
here=$(dirname "$0")
cp "$eclair/packages/protocol/schema/eclair.schema.json" "$here/eclair.schema.json"
git -C "$eclair" rev-parse HEAD > "$here/VERSION"
echo "Protocol at $(cat "$here/VERSION")"
