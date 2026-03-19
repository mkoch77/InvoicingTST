FROM postgres:17

COPY install-postgres-schema.sql /docker-entrypoint-initdb.d/01-schema.sql
COPY install-auth-schema.sql /docker-entrypoint-initdb.d/02-auth-schema.sql
COPY install-customer-schema.sql /docker-entrypoint-initdb.d/03-customer-schema.sql
RUN chmod 644 /docker-entrypoint-initdb.d/*.sql
