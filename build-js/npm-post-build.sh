#!/bin/sh

# SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

set -e

# Add licenses for source maps
if [ -d "js" ]; then
	for f in js/*.js; do
		# If license file and source map exists copy license for the source map
		if [ -f "$f.license" ] && [ -f "$f.map" ]; then
			# Remove existing link
			[ -e "$f.map.license" ] || [ -L "$f.map.license" ] && rm "$f.map.license"
			# Create a new link
			ln -s "$(basename "$f.license")" "$f.map.license"
		fi
	done
	echo "Copying licenses for sourcemaps done"
else
	echo "This script needs to be executed from the root of the repository"
	exit 1
fi
