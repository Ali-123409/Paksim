export default async function handler(req, res) {
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
  if (req.method === 'OPTIONS') return res.status(200).end();

  const query = (req.query.query || req.query.q || '').trim();
  if (!query) return res.status(400).json({ error: 'Missing ?query= parameter' });

  const isMobile = /^92\d{9,12}$/.test(query);
  const isCnic   = /^\d{13}$/.test(query);
  if (!isMobile && !isCnic) {
    return res.status(400).json({
      error: 'Invalid format. Use mobile with country code (92XXXXXXXXXX) or 13-digit CNIC.'
    });
  }

  try {
    const response = await fetch('https://pakistandatabase.com/databases/sim.php', {
      method: 'POST',
      headers: {
        'Content-Type':   'application/x-www-form-urlencoded',
        'User-Agent':     'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        'Referer':        'https://pakistandatabase.com/',
        'Origin':         'https://pakistandatabase.com',
        'Accept-Language':'en-US,en;q=0.9',
      },
      body: `search_query=${encodeURIComponent(query)}`
    });

    if (!response.ok) throw new Error(`Upstream HTTP ${response.status}`);

    const html = await response.text();

    if (/no record|not found|no result|invalid/i.test(html)) {
      return res.status(404).json({ error: 'No records found for this number.' });
    }

    const records = parseTable(html);
    if (!records.length) {
      return res.status(404).json({ error: 'No subscriber data found in response.' });
    }

    return res.status(200).json({
      query,
      query_type: isMobile ? 'mobile' : 'cnic',
      count: records.length,
      records
    });

  } catch (err) {
    return res.status(500).json({ error: 'Fetch failed: ' + err.message });
  }
}

function parseTable(html) {
  // Strip scripts/styles first
  html = html.replace(/<script[\s\S]*?<\/script>/gi, '')
             .replace(/<style[\s\S]*?<\/style>/gi, '');

  const tbodyMatch = html.match(/<tbody[\s\S]*?>([\s\S]*?)<\/tbody>/i);
  if (!tbodyMatch) return [];

  const rows = tbodyMatch[1].match(/<tr[\s\S]*?>([\s\S]*?)<\/tr>/gi) || [];
  const results = [];

  for (const row of rows) {
    const cells = (row.match(/<td[\s\S]*?>([\s\S]*?)<\/td>/gi) || [])
      .map(td => td.replace(/<[^>]+>/g, '').replace(/&nbsp;/g, ' ').trim());

    if (cells.length >= 2 && cells.some(c => c)) {
      results.push({
        mobile:  cells[0] || null,
        name:    cells[1] || null,
        cnic:    cells[2] || null,
        address: cells[3] || null,
      });
    }
  }
  return results;
}
