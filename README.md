# Edutorium WebSocket Server - Standalone Project

## 🎯 Project Overview
Standalone WebSocket server for Edutorium Battle System real-time functionality.

## 📁 Project Structure
```
websocket-server/
├── Dockerfile
├── docker-compose.yml
├── composer.json
├── battle-server.php
├── config/
│   └── config.php
├── logs/
└── README.md
```

## 🚀 Quick Start

### 1. Build and Run
```bash
docker-compose up -d
```

### 2. Check Status
```bash
docker-compose logs -f
```

### 3. Test Connection
```javascript
const ws = new WebSocket('ws://localhost:8080/');
ws.onopen = () => console.log('Connected!');
```

## 🔧 Configuration

### Environment Variables
- `SUPABASE_URL`: Your Supabase project URL
- `SUPABASE_KEY`: Your Supabase anon key
- `WEBSOCKET_PORT`: Port for WebSocket server (default: 8080)
- `LOG_LEVEL`: Logging level (debug, info, error)

### Database Settings
Update your main app's database `websocket_url` to:
```
ws://your-websocket-server-domain:8080/
```

## 📊 Monitoring

### Health Check
```bash
curl http://localhost:8080/health
```

### Logs
```bash
docker-compose logs -f websocket-server
```

## 🔗 Integration

### Main App Configuration
In your main Edutorium app, update the WebSocket URL in the database:
```sql
UPDATE settings SET value = 'ws://your-websocket-server:8080/' WHERE key = 'websocket_url';
```

### Coolify Deployment
1. Create new project in Coolify
2. Connect to this WebSocket server repository
3. Set environment variables
4. Deploy separately from main app

## 🎯 Benefits

- ✅ **Independent Scaling**: Scale WebSocket server based on concurrent users
- ✅ **Better Performance**: Dedicated resources for real-time connections
- ✅ **Easier Debugging**: Isolated logs and monitoring
- ✅ **Flexible Deployment**: Deploy to different servers/regions
- ✅ **Simplified Architecture**: Clean separation of concerns
- ✅ **Better Reliability**: Independent failure recovery