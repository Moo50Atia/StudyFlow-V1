ARG CLAMAV_IMAGE=clamav/clamav:1.4.3@sha256:75fb5fd95fcbe1d7e6d240c369c1572b686ee2c95949d1042b5148de8eddebb4
FROM ${CLAMAV_IMAGE}
# Match PHP's maximum individual file size; keep daemon access on the private network.
RUN sed -i '/^StreamMaxLength /d; /^MaxFileSize /d; /^MaxScanSize /d' /etc/clamav/clamd.conf && \
    printf '\nStreamMaxLength 100M\nMaxFileSize 100M\nMaxScanSize 200M\n' >> /etc/clamav/clamd.conf
