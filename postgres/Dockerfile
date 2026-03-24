FROM postgres:17

COPY install-postgres-schema.sql /docker-entrypoint-initdb.d/01-schema.sql
COPY install-auth-schema.sql /docker-entrypoint-initdb.d/02-auth-schema.sql
COPY install-customer-schema.sql /docker-entrypoint-initdb.d/03-customer-schema.sql
COPY install-pricing-schema.sql /docker-entrypoint-initdb.d/04-pricing-schema.sql
COPY install-vault-schema.sql /docker-entrypoint-initdb.d/05-vault-schema.sql
COPY install-log-schema.sql /docker-entrypoint-initdb.d/06-log-schema.sql
COPY install-backup-schema.sql /docker-entrypoint-initdb.d/07-backup-schema.sql
COPY install-configurator-schema.sql /docker-entrypoint-initdb.d/08-configurator-schema.sql
COPY install-server-service-schema.sql /docker-entrypoint-initdb.d/09-server-service-schema.sql
COPY install-company-schema.sql /docker-entrypoint-initdb.d/10-company-schema.sql
RUN chmod 644 /docker-entrypoint-initdb.d/*.sql
