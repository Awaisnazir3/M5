const http = require('http');
const net = require('net');
const url = require('url');

const server = http.createServer((req, res) => {
  const parsed = url.parse(req.url);
  const options = {
    hostname: parsed.hostname,
    port: parsed.port || 80,
    path: parsed.path,
    method: req.method,
    headers: req.headers
  };
  const proxyReq = http.request(options, proxyRes => {
    res.writeHead(proxyRes.statusCode, proxyRes.headers);
    proxyRes.pipe(res);
  });
  proxyReq.on('error', err => {
    res.writeHead(502);
    res.end('Bad gateway: ' + err.message);
  });
  req.pipe(proxyReq);
});

server.on('connect', (req, clientSocket, head) => {
  const [host, port] = req.url.split(':');
  const serverSocket = net.connect(port || 443, host, () => {
    clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
    if (head && head.length) serverSocket.write(head);
    serverSocket.pipe(clientSocket);
    clientSocket.pipe(serverSocket);
  });
  serverSocket.on('error', () => clientSocket.destroy());
  clientSocket.on('error', () => serverSocket.destroy());
});

server.listen(8888, '0.0.0.0', () => {
  console.log('Proxy listening on 0.0.0.0:8888');
});

