# The image of act's job container: catthehacker's ubuntu image plus what GitHub's own runner image has and the workflow
# uses (locale-gen, the PostgreSQL and MySQL clients, nvm). Built by .dev/ci/act.sh. Development only.
FROM catthehacker/ubuntu:act-24.04
RUN apt-get update \
    && apt-get install -y --no-install-recommends locales postgresql-client mysql-client \
    && rm -rf /var/lib/apt/lists/*
RUN git clone --depth 1 --branch v0.40.3 https://github.com/nvm-sh/nvm.git /root/.nvm && rm -rf /root/.nvm/.git
