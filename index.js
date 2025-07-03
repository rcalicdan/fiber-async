
// Store performance results for comparison
let performanceResults = {};

// API endpoints (same as PHP)
const apis = {
    'jsonplaceholder': 'https://jsonplaceholder.typicode.com/posts/1',
    'random_user': 'https://randomuser.me/api/',
    'cat_fact': 'https://catfact.ninja/fact',
    'dog_ceo': 'https://dog.ceo/api/breeds/image/random',
    'advice': 'https://api.adviceslip.com/advice',
    'joke': 'https://official-joke-api.appspot.com/random_joke',
    'age_predictor': 'https://api.agify.io/?name=michael',
    'gender_predictor': 'https://api.genderize.io/?name=luc',
    'nationalize': 'https://api.nationalize.io/?name=nathaniel',
    'ipify': 'https://api.ipify.org?format=json',
    'chuck_norris': 'https://api.chucknorris.io/jokes/random',
    'open_meteo': 'https://api.open-meteo.com/v1/forecast?latitude=35&longitude=139&current_weather=true',
    'pokeapi': 'https://pokeapi.co/api/v2/pokemon/pikachu',
    //'bored': 'https://www.boredapi.com/api/activity',
    'uselessfacts': 'https://uselessfacts.jsph.pl/random.json?language=en',
};

async function runJavaScriptAsync() {
    const resultsDiv = document.getElementById('js-results');
    resultsDiv.innerHTML = '<div class="js-results"><h2>🚀 JavaScript Async Fetch Results</h2><div class="loading">🔄 Running concurrent requests...</div></div>';

    const startTime = performance.now();
    const startMemory = performance.memory ? performance.memory.usedJSHeapSize : 0;

    try {
        const promises = Object.entries(apis).map(async ([name, url]) => {
            const requestStart = performance.now();

            try {
                const response = await fetch(url, {
                    headers: {
                        'User-Agent': 'JavaScript-Async-Fetch/1.0'
                    }
                });

                const requestEnd = performance.now();
                const data = await response.text();

                return {
                    name,
                    url,
                    data: {
                        body: data
                    },
                    status: response.ok ? 'success' : 'error',
                    request_time: (requestEnd - requestStart) / 1000,
                    http_code: response.status
                };
            } catch (error) {
                const requestEnd = performance.now();
                return {
                    name,
                    url,
                    data: null,
                    status: 'error',
                    error: error.message,
                    request_time: (requestEnd - requestStart) / 1000
                };
            }
        });

        const results = await Promise.all(promises);
        const endTime = performance.now();
        const endMemory = performance.memory ? performance.memory.usedJSHeapSize : 0;

        const benchmark = {
            execution_time: (endTime - startTime) / 1000,
            memory_used: endMemory - startMemory,
            peak_memory: performance.memory ? performance.memory.totalJSHeapSize : 0
        };

        displayJavaScriptResults(results, benchmark, 'JavaScript Async Fetch');

        // Store results for comparison
        performanceResults['js_async'] = {
            execution_time: benchmark.execution_time,
            successful_requests: results.filter(r => r.status === 'success').length,
            failed_requests: results.filter(r => r.status === 'error').length,
            avg_request_time: results.reduce((sum, r) => sum + r.request_time, 0) / results.length
        };

    } catch (error) {
        resultsDiv.innerHTML += `<div class="error"><h3>❌ Error occurred:</h3><p>${error.message}</p></div>`;
    }
}

async function runJavaScriptSync() {
    const resultsDiv = document.getElementById('js-results');
    resultsDiv.innerHTML = '<div class="js-results"><h2>🐌 JavaScript Synchronous Results</h2><div class="loading">🔄 Running sequential requests...</div></div>';

    const startTime = performance.now();
    const startMemory = performance.memory ? performance.memory.usedJSHeapSize : 0;
    const results = [];

    try {
        for (const [name, url] of Object.entries(apis)) {
            const requestStart = performance.now();

            try {
                const response = await fetch(url, {
                    headers: {
                        'User-Agent': 'JavaScript-Sync-Fetch/1.0'
                    }
                });

                const requestEnd = performance.now();
                const data = await response.text();

                results.push({
                    name,
                    url,
                    data: {
                        body: data
                    },
                    status: response.ok ? 'success' : 'error',
                    request_time: (requestEnd - requestStart) / 1000,
                    http_code: response.status
                });
            } catch (error) {
                const requestEnd = performance.now();
                results.push({
                    name,
                    url,
                    data: null,
                    status: 'error',
                    error: error.message,
                    request_time: (requestEnd - requestStart) / 1000
                });
            }
        }

        const endTime = performance.now();
        const endMemory = performance.memory ? performance.memory.usedJSHeapSize : 0;

        const benchmark = {
            execution_time: (endTime - startTime) / 1000,
            memory_used: endMemory - startMemory,
            peak_memory: performance.memory ? performance.memory.totalJSHeapSize : 0
        };

        displayJavaScriptResults(results, benchmark, 'JavaScript Synchronous');

        // Store results for comparison
        performanceResults['js_sync'] = {
            execution_time: benchmark.execution_time,
            successful_requests: results.filter(r => r.status === 'success').length,
            failed_requests: results.filter(r => r.status === 'error').length,
            avg_request_time: results.reduce((sum, r) => sum + r.request_time, 0) / results.length
        };

    } catch (error) {
        resultsDiv.innerHTML += `<div class="error"><h3>❌ Error occurred:</h3><p>${error.message}</p></div>`;
    }
}

function displayJavaScriptResults(results, benchmark, type) {
    const resultsDiv = document.getElementById('js-results');

    const successfulRequests = results.filter(r => r.status === 'success');
    const failedRequests = results.filter(r => r.status === 'error');

    const requestTimes = results.map(r => r.request_time);
    const avgTime = requestTimes.reduce((sum, time) => sum + time, 0) / requestTimes.length;
    const maxTime = Math.max(...requestTimes);
    const minTime = Math.min(...requestTimes);

    // Calculate async benefits
    let asyncBenefits = '';
    if (type.includes('Async')) {
        const totalSyncTime = requestTimes.reduce((sum, time) => sum + time, 0);
        const timeSaved = totalSyncTime - benchmark.execution_time;
        const percentSaved = totalSyncTime > 0 ? (timeSaved / totalSyncTime) * 100 : 0;

        asyncBenefits = `
                    <div style="margin-top: 15px; padding: 10px; background: #d1ecf1; border-radius: 5px;">
                        <strong>🚀 Async Benefits:</strong><br>
                        If run synchronously: ${totalSyncTime.toFixed(3)}s<br>
                        Time saved: ${timeSaved.toFixed(3)}s (${percentSaved.toFixed(1)}% faster)
                    </div>
                `;
    }

    let html = `
                <div class="js-results">
                    <h2>🌐 ${type} Results</h2>
                    
                    <div class="benchmark">
                        <h3>📊 ${type} Performance Metrics</h3>
                        <p><strong>⏱️ Time to Complete all requests:</strong> ${benchmark.execution_time.toFixed(4)} seconds</p>
                        <p><strong>🧠 Memory Used:</strong> ${formatBytes(benchmark.memory_used)}</p>
                        <p><strong>📈 Peak Memory:</strong> ${formatBytes(benchmark.peak_memory)}</p>
                    </div>

                    <div class="timing-summary">
                        <h3>🕒 Individual Request Timing Analysis</h3>
                        <div class="timing-stats">
                            <div class="stat-item"><strong>Total Requests</strong><br>${results.length}</div>
                            <div class="stat-item"><strong>✅ Successful</strong><br>${successfulRequests.length}</div>
                            <div class="stat-item"><strong>❌ Failed</strong><br>${failedRequests.length}</div>
                            <div class="stat-item"><strong>📊 Avg Time</strong><br>${avgTime.toFixed(3)}s</div>
                            <div class="stat-item"><strong>⚡ Fastest</strong><br>${minTime.toFixed(3)}s</div>
                            <div class="stat-item"><strong>🐌 Slowest</strong><br>${maxTime.toFixed(3)}s</div>
                        </div>
                        ${asyncBenefits}
                    </div>
            `;

    results.forEach(result => {
        const timingClass = getTimingClass(result.request_time);
        html += `
                    <div class="api-result">
                        <h4>🌐 ${result.name.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</h4>
                        <p><strong>URL:</strong> ${result.url}</p>
                        <p><strong>Status:</strong> ${result.status === 'success' ? '✅ Success' : '❌ Failed'}</p>
                        <div class="timing-info ${timingClass}">
                            <strong>⏱️ Request Time:</strong> ${result.request_time.toFixed(4)} seconds
                            ${result.status === 'error' && result.error ? ` - <strong>Error:</strong> ${result.error}` : ''}
                        </div>
                    </div>
                `;
    });

    html += '</div>';
    resultsDiv.innerHTML = html;
}

function getTimingClass(time) {
    if (time < 1.0) return 'timing-fast';
    if (time < 3.0) return 'timing-medium';
    return 'timing-slow';
}

function formatBytes(bytes, precision = 2) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(precision)) + ' ' + sizes[i];
}

async function runCompleteComparison() {
    // Run both JavaScript tests
    await runJavaScriptAsync();
    await new Promise(resolve => setTimeout(resolve, 2000)); // Wait 2 seconds between tests
    await runJavaScriptSync();

    // Show comparison table
    showPerformanceComparison();
}

function showPerformanceComparison() {
    if (Object.keys(performanceResults).length === 0) {
        alert('Please run some tests first to see the comparison!');
        return;
    }

    const comparisonDiv = document.getElementById('performance-comparison');
    const tableBody = document.getElementById('comparison-table-body');

    // Clear existing content
    tableBody.innerHTML = '';

    // Sort results by execution time (fastest first)
    const sortedResults = Object.entries(performanceResults)
        .sort(([, a], [, b]) => a.execution_time - b.execution_time);

    sortedResults.forEach(([method, data], index) => {
        const row = document.createElement('tr');
        if (index === 0) row.classList.add('winner'); // Highlight the winner

        const methodName = method.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
        const performanceScore = (data.successful_requests / data.execution_time).toFixed(2);

        row.innerHTML = `
                    <td>${methodName} ${index === 0 ? '🏆' : ''}</td>
                    <td>${data.execution_time.toFixed(4)}</td>
                    <td>${data.successful_requests}</td>
                    <td>${data.failed_requests}</td>
                    <td>${data.avg_request_time.toFixed(4)}</td>
                    <td>${performanceScore}</td>
                `;

        tableBody.appendChild(row);
    });

    comparisonDiv.style.display = 'block';
}

// Auto-hide loading messages
setTimeout(() => {
    const loadingDivs = document.querySelectorAll('.loading');
    loadingDivs.forEach(div => {
        div.style.display = 'none';
    });
}, 5000);
