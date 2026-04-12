# Greenhood production image — extends the official OpenEMR production image.
# Upstream layout is defined on Docker Hub (openemr/openemr); do not edit files under /docker/library.
#
# Pin UPSTREAM_IMAGE to a digest in production for reproducible builds, e.g.:
#   docker build --build-arg UPSTREAM_IMAGE=openemr/openemr:latest@sha256:...
#
ARG UPSTREAM_IMAGE=openemr/openemr:latest
FROM ${UPSTREAM_IMAGE}

COPY docker/greenhood/entrypoint-wrapper.sh /usr/local/bin/greenhood-openemr-entrypoint.sh
RUN chmod +x /usr/local/bin/greenhood-openemr-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/greenhood-openemr-entrypoint.sh"]
