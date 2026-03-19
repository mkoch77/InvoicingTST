FROM postgres:17

COPY install-postgres-schema.sql /docker-entrypoint-initdb.d/01-schema.sql
RUN chmod 644 /docker-entrypoint-initdb.d/01-schema.sql
