import paramiko

HOST = '20.187.144.64'
USER = 'adminagricorn'
PASS = 'edrizLoLiPoP30'

def main():
    print("Connecting to VM...")
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, password=PASS, timeout=15)
    
    def run_sudo(cmd):
        print(f"Running: {cmd}")
        stdin, stdout, stderr = client.exec_command(f"sudo -S bash -c \"{cmd}\"")
        stdin.write(PASS + '\n')
        stdin.flush()
        out = stdout.read().decode()
        err = stderr.read().decode()
        if out: print(out)
        if err: print(err)

    print("1. Completing Git Initialization...")
    run_sudo("sudo -u www-data git -C /var/www/html/AgriCorn fetch")
    run_sudo("sudo -u www-data git -C /var/www/html/AgriCorn reset --hard origin/main || sudo -u www-data git -C /var/www/html/AgriCorn reset --hard origin/master")

    print("2. Setting up Auto-Pull Script...")
    script_content = """#!/bin/bash
cd /var/www/html/AgriCorn
while true; do
    git fetch origin main >/dev/null 2>&1
    git reset --hard origin/main >/dev/null 2>&1
    sleep 5
done
"""
    # Write script to /usr/local/bin/agricorn-autopull.sh
    stdin, stdout, stderr = client.exec_command("sudo -S tee /usr/local/bin/agricorn-autopull.sh > /dev/null")
    stdin.write(PASS + '\n')
    stdin.write(script_content)
    stdin.close()
    
    run_sudo("chmod +x /usr/local/bin/agricorn-autopull.sh")
    run_sudo("chown www-data:www-data /usr/local/bin/agricorn-autopull.sh")

    print("3. Creating Systemd Service...")
    service_content = """[Unit]
Description=AgriCorn Auto Git Pull Service
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
ExecStart=/usr/local/bin/agricorn-autopull.sh
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
"""
    stdin, stdout, stderr = client.exec_command("sudo -S tee /etc/systemd/system/agricorn-autopull.service > /dev/null")
    stdin.write(PASS + '\n')
    stdin.write(service_content)
    stdin.close()

    run_sudo("systemctl daemon-reload")
    run_sudo("systemctl enable agricorn-autopull.service")
    run_sudo("systemctl restart agricorn-autopull.service")

    print("Auto-pull service running!")
    client.close()

if __name__ == '__main__':
    main()
