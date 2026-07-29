# Agent SSH public key

Add the contents of `agent_ed25519.pub` to the VPS:

```
cat tools/research-bridge/keys/agent_ed25519.pub >> /root/.ssh/authorized_keys
```

The private key (`agent_ed25519`) must stay private and is gitignored.
Generate a fresh key per environment if needed:

```bash
ssh-keygen -t ed25519 -f tools/research-bridge/keys/agent_ed25519 -N "" -C "ssm-research-agent"
```
