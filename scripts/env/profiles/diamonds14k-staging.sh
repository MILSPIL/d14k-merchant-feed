#!/usr/bin/env bash

D14K_PROFILE_LABEL="${D14K_PROFILE_LABEL:-14karat staging}"
D14K_REMOTE_SSH_ALIAS="${D14K_REMOTE_SSH_ALIAS:-14karat}"
D14K_REMOTE_SITE_URL="${D14K_REMOTE_SITE_URL:-https://staging.diamonds14k.com}"
D14K_REMOTE_WP_PATH="${D14K_REMOTE_WP_PATH:-/home/diamond2/staging.diamonds14k.com}"
D14K_REMOTE_PLUGIN_PATH="${D14K_REMOTE_PLUGIN_PATH:-/home/diamond2/staging.diamonds14k.com/wp-content/plugins/gmc-feed-for-woocommerce/}"
D14K_PLUGIN_SLUG="${D14K_PLUGIN_SLUG:-gmc-feed-for-woocommerce}"
D14K_SMOKE_KIND="${D14K_SMOKE_KIND:-gmc_prom}"
D14K_GMC_LANGS="${D14K_GMC_LANGS:-uk ru}"
D14K_PROM_FEED_URL="${D14K_PROM_FEED_URL:-https://staging.diamonds14k.com/marketplace-feed/prom/}"
D14K_PROM_CHECK_MODE="${D14K_PROM_CHECK_MODE:-optional}"
D14K_RUN_HTTP_CHECKS_DEFAULT="${D14K_RUN_HTTP_CHECKS_DEFAULT:-1}"
