
ARG PHP_VERSION=8.5
ARG FRANKENPHP_VERSION=1.13

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION}-bookworm

# System dependencies and terminal utilities
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        bash \
        bash-completion \
        less \
        nano \
        git \
        unzip \
        curl \
        procps \
        ncurses-term; \
    install-php-extensions \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        zip; \
    rm -rf /var/lib/apt/lists/*

# PHP configuration
COPY conf.d/app.ini $PHP_INI_DIR/conf.d/app.ini

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

# Interactive Bash configuration
RUN set -eux; \
    printf '%s\n' \
        'export HISTSIZE=10000' \
        'export HISTFILESIZE=20000' \
        'export HISTCONTROL=ignoreboth:erasedups' \
        'shopt -s histappend' \
        'shopt -s checkwinsize' \
        'bind "set completion-ignore-case on"' \
        'bind "set show-all-if-ambiguous on"' \
        'bind "\"\e[A\": history-search-backward"' \
        'bind "\"\e[B\": history-search-forward"' \
        'PS1="\[\e[1;32m\]\u@\h\[\e[0m\]:\[\e[1;34m\]\w\[\e[0m\]\$ "' \
        'if [ -f /usr/share/bash-completion/bash_completion ]; then' \
        '    . /usr/share/bash-completion/bash_completion' \
        'fi' \
        >> /etc/bash.bashrc

WORKDIR /app
