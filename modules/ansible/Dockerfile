# Preparation image only: Ansible is installed into a separate persistent volume.
FROM debian:trixie-slim
ARG ANSIBLE_UID=1001
ARG ANSIBLE_GID=1001
RUN apt-get update && apt-get install -y --no-install-recommends python3-venv ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid "$ANSIBLE_GID" ansible \
    && useradd --uid "$ANSIBLE_UID" --gid "$ANSIBLE_GID" --home-dir /var/lib/pxe-ansible --no-create-home --shell /usr/sbin/nologin ansible \
    && install -d -o ansible -g ansible -m 0700 /var/lib/pxe-ansible
COPY app/ansible_runtime.py /usr/local/lib/pxe-ansible/runtime.py
USER ansible
VOLUME ["/var/lib/pxe-ansible"]
ENTRYPOINT ["python3", "/usr/local/lib/pxe-ansible/runtime.py", "--runtime", "/var/lib/pxe-ansible"]
