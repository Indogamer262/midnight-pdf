// MidnightPDF Test Service
const express = require('express');
const app = express();
const PORT = 3000;

app.get('/api/status', (req, res) => {
    res.json({
        app: 'MidnightPDF',
        status: 'online',
        uptime: process.uptime(),
        version: '1.0.0'
    });
});

app.listen(PORT, () => {
    console.log();
});
