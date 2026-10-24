#!/usr/bin/env bash

# The problem is that we do not want to remove the configuration file, just disable it for a few tasks, then enable it
#
# For reference, see
#
# - https://example.invalid/user/languages/php#Disabling-preinstalled-PHP-extensions
# - https://example.invalid/user/languages/php#Custom-PHP-configuration

config="/home/travis/.phpenv/versions/$(phpenv version-name)/etc/conf.d/xdebug.ini"

function xdebug-disable() {
    if [[ -f $config ]]; then
        mv $config "$config.bak"
    fi
}

function xdebug-enable() {
    if [[ -f "$config.bak" ]]; then
        mv "$config.bak" $config
    fi
}
