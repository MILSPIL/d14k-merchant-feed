#!/usr/bin/env bash

D14K_PROFILE_LABEL="${D14K_PROFILE_LABEL:-Beautyfill staging}"
D14K_REMOTE_SSH_ALIAS="${D14K_REMOTE_SSH_ALIAS:-filler-hostinger}"
D14K_REMOTE_SITE_URL="${D14K_REMOTE_SITE_URL:-https://beautyfill.shop}"
D14K_REMOTE_WP_PATH="${D14K_REMOTE_WP_PATH:-/home/u782807436/domains/beautyfill.shop/public_html}"
D14K_REMOTE_PLUGIN_PATH="${D14K_REMOTE_PLUGIN_PATH:-/home/u782807436/domains/beautyfill.shop/public_html/wp-content/plugins/d14k-merchant-feed/}"
D14K_REMOTE_FEED_URL="${D14K_REMOTE_FEED_URL:-https://beautyfill.shop/marketplace-feed/horoshop/}"
D14K_MARKETPLACE_FEED_URL="${D14K_MARKETPLACE_FEED_URL:-https://beautyfill.shop/marketplace-feed/horoshop/}"
D14K_PLUGIN_SLUG="${D14K_PLUGIN_SLUG:-d14k-merchant-feed}"
D14K_SMOKE_KIND="${D14K_SMOKE_KIND:-marketplace_feed}"
D14K_RUN_HTTP_CHECKS_DEFAULT="${D14K_RUN_HTTP_CHECKS_DEFAULT:-1}"
D14K_CACHE_FLUSH_COMMAND="${D14K_CACHE_FLUSH_COMMAND:-wp --path='/home/u782807436/domains/beautyfill.shop/public_html' cache flush}"
