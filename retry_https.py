import paramiko

HOST = '20.187.144.64'
USER = 'adminagricorn'
PASS = 'edrizLoLiPoP30'

def run_sudo_cmd(client, cmd):
    print(f"Running: sudo {cmd}")
    stdin, stdout, stderr = client.exec_command(f"sudo -S {cmd}")
    stdin.write(PASS + '\n')
    stdin.flush()
    exit_status = stdout.channel.recv_exit_status()
    out = stdout.read().decode('utf-8', errors='ignore').strip()
    err = stderr.read().decode('utf-8', errors='ignore').strip()
    if out: print("STDOUT:", out[:500] + ("..." if len(out) > 500 else ""))
    if err: print("STDERR:", err[:500] + ("..." if len(err) > 500 else ""))
    return exit_status

def main():
    print("Connecting to VM...")
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, password=PASS, timeout=15)
    
    print("Requesting SSL Certificate...")
    status = run_sudo_cmd(client, "certbot --apache --non-interactive --agree-tos -m admin@agricorn.online -d agricorn.online -d www.agricorn.online")
    if status == 0:
        print("HTTPS Setup Complete!")
    else:
        print("Failed to get certificate. DNS might still be propagating.")

    client.close()

if __name__ == '__main__':
    main()
