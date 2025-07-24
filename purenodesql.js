// purenodesql.js (Corrected)

const mysql = require('mysql2/promise');
const { performance } = require('perf_hooks');

// --- Configuration ---
const mysqlConfig = {
    host: 'localhost',
    user: 'hey',
    password: '1234',
    database: 'yo',
    port: 3306,
};

// You can change these values to match the PHP tests
const poolSize = 100; // Set the desired pool size for the test
const queryCount = 5000;
const latencyMilliseconds = 10;

// --- Helper Functions ---

// Function to introduce a simulated delay
const delay = (ms) => new Promise(resolve => setTimeout(resolve, ms));

async function setupDatabase(config, rowCount) {
    const connection = await mysql.createConnection(config);
    await connection.execute("DROP TABLE IF EXISTS perf_test");
    await connection.execute("CREATE TABLE perf_test (id INT AUTO_INCREMENT PRIMARY KEY, data TEXT)");

    // Use a prepared statement for efficient insertion
    // THE FIX IS HERE: Removed the brackets [] around 'stmt'
    const stmt = await connection.prepare("INSERT INTO perf_test (data) VALUES (?)");
    for (let i = 0; i < rowCount; i++) {
        await stmt.execute(['data-' + i]);
    }
    await stmt.close();
    await connection.end();
}

// =================================================================
// == STEP 1: DEFINE THE PERFORMANCE WORKLOADS
// =================================================================

async function runSyncPerformanceTest(config, count, latency) {
    const connection = await mysql.createConnection(config);
    const sql = "SELECT id, data FROM perf_test WHERE id = ?";

    const startTime = performance.now();
    const startMemory = process.memoryUsage().heapUsed;
    const returnedData = [];

    for (let i = 0; i < count; i++) {
        const idToFind = Math.floor(Math.random() * count) + 1;
        // await inside the loop makes it serial (synchronous-style)
        const [rows] = await connection.execute(sql, [idToFind]);
        returnedData.push(rows[0]);
        await delay(latency);
    }

    await connection.end();
    const endTime = performance.now();
    const endMemory = process.memoryUsage().heapUsed;
    return {
        metrics: { time: (endTime - startTime) / 1000, memory: endMemory - startMemory },
        data: returnedData,
    };
}


async function runAsyncPerformanceTest(config, count, latency, poolSize) {
    const pool = mysql.createPool({ ...config, connectionLimit: poolSize, waitForConnections: true });
    const sql = "SELECT id, data FROM perf_test WHERE id = ?";

    const startTime = performance.now();
    const startMemory = process.memoryUsage().heapUsed;

    const tasks = [];
    for (let i = 0; i < count; i++) {
        const task = async () => {
            let connection;
            try {
                const idToFind = Math.floor(Math.random() * count) + 1;
                // Get a connection from the pool
                connection = await pool.getConnection();
                const [rows] = await connection.execute(sql, [idToFind]);
                await delay(latency);
                return rows[0];
            } finally {
                // VERY IMPORTANT: Always release the connection back to the pool
                if (connection) connection.release();
            }
        };
        tasks.push(task());
    }

    const returnedData = await Promise.all(tasks);

    await pool.end();
    const endTime = performance.now();
    const endMemory = process.memoryUsage().heapUsed;

    return {
        metrics: { time: (endTime - startTime) / 1000, memory: endMemory - startMemory },
        data: returnedData,
    };
}


// --- Main Execution Logic ---
async function main() {
    try {
        console.log("\n=================================================");
        console.log("  PERFORMANCE TEST: MySQL (Node.js)");
        console.log(`  (${queryCount} queries, ${latencyMilliseconds}ms simulated latency each, Pool Size: ${poolSize})`);
        console.log("=================================================");

        console.log("\n-- [SETUP] Preparing database... --");
        await setupDatabase(mysqlConfig, queryCount);
        console.log("[SETUP] Database is ready.");

        // --- Run Synchronous Test ---
        console.log("\n-- [SYNC] Running performance test... --");
        const syncResult = await runSyncPerformanceTest(mysqlConfig, queryCount, latencyMilliseconds);
        console.log("[SYNC] Test complete.");

        // --- Run Async Test ---
        console.log("\n-- [ASYNC Node.js] Running performance test... --");
        const asyncNodeResult = await runAsyncPerformanceTest(mysqlConfig, queryCount, latencyMilliseconds, poolSize);
        console.log("[ASYNC Node.js] Test complete.");

        // --- Final Report ---
        console.log("\n\n=========================================================================================");
        console.log("                                FINAL PERFORMANCE REPORT                                 ");
        console.log("=========================================================================================");
        console.log("| Mode                  | Execution Time      | Peak Memory Usage      | QPS        |");
        console.log("|-----------------------|---------------------|------------------------|------------|");

        const syncTime = syncResult.metrics.time;
        const syncMem = syncResult.metrics.memory;
        console.log(`| Sync (Node.js)        | ${(syncTime.toFixed(4) + ' s').padEnd(19)} | ${( (syncMem / 1024).toFixed(2) + ' KB').padEnd(22)} | ${( (queryCount / syncTime).toFixed(2)).padEnd(10) } |`);

        const nodeTime = asyncNodeResult.metrics.time;
        const nodeMem = asyncNodeResult.metrics.memory;
        console.log(`| Async (Node.js)       | ${(nodeTime.toFixed(4) + ' s').padEnd(19)} | ${( (nodeMem / 1024).toFixed(2) + ' KB').padEnd(22)} | ${( (queryCount / nodeTime).toFixed(2)).padEnd(10) } |`);
        
        console.log("=========================================================================================\n");

        const nodeImprovement = ((syncTime - nodeTime) / syncTime) * 100;
        console.log("Conclusion: For this I/O-bound workload...");
        console.log(`  - Async Node.js was ${nodeImprovement.toFixed(2)}% faster than serial Node.js.`);

    } catch (e) {
        console.error("\n\n--- A TEST FAILED ---");
        console.error("Error: " + e.message);
        console.error(e.stack);
    }
}

main();