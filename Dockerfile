# WordPress dev image: stock WP + wp-cli for setup/tests.
ARG WP_BASE_IMAGE=wordpress:php8.2-apache
FROM ${WP_BASE_IMAGE}

# curl/unzip are handy for debugging; wp-cli for version checks inside the web container.
RUN apt-get update \
  && apt-get install -y --no-install-recommends curl unzip less \
  && curl -o /usr/local/bin/wp -L https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
  && chmod +x /usr/local/bin/wp \
  && apt-get clean && rm -rf /var/lib/apt/lists/*

# Apache rewrite is already enabled in the base image for permalinks.
