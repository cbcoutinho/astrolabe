#!/bin/bash
# Install Astrolabe app from mounted volume
set -euox pipefail

echo "Installing Astrolabe app..."

if [ -d /opt/apps/astrolabe ]; then
    echo "Development astrolabe app found at /opt/apps/astrolabe"

    # Remove any existing astrolabe app in custom_apps
    if [ -e /var/www/html/custom_apps/astrolabe ]; then
        rm -rf /var/www/html/custom_apps/astrolabe
    fi

    # Create symlink from custom_apps to the mounted development version
    ln -sf /opt/apps/astrolabe /var/www/html/custom_apps/astrolabe

    echo "Enabling astrolabe app from /opt/apps (development mode via symlink)"
    php /var/www/html/occ app:enable astrolabe
else
    echo "ERROR: Astrolabe app not found at /opt/apps/astrolabe"
    exit 1
fi

echo "Astrolabe app installed successfully"
