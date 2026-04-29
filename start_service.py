import paramiko

HOST = '20.187.144.64'
USER = 'adminagricorn'
PASS = 'edrizLoLiPoP30'

def main():
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, password=PASS, timeout=15)
    
    stdin, stdout, stderr = client.exec_command("sudo -S systemctl restart agricorn-autopull.service")
    stdin.write(PASS + '\n')
    stdin.flush()
    stdout.channel.recv_exit_status()
    
    client.close()
    print("Service started!")

if __name__ == '__main__':
    main()
