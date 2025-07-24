const { performance } = require('perf_hooks');

function delay(ms, value, shouldThrow = false) {
    return new Promise((resolve, reject) => {
        setTimeout(() => {
            console.log(`Finished: ${value}`);
            if (shouldThrow) reject(new Error(`Error from ${value}`));
            else resolve(`Success from ${value}`);
        }, ms);
    });
}

async function testRace() {
    const start = performance.now();

    try {
        const result = await Promise.race([
            delay(1000, 1, true),           // reject
            delay(2000, 2),                 // resolve
            delay(3000, 3),                 // resolve
        ]);
        console.log("Race result:", result);
    } catch (e) {
        console.log("Race threw:", e.message);
    }

    const end = performance.now();
    console.log("Race Execution time:", ((end - start) / 1000).toFixed(4), "seconds\n");
}

async function testAny() {
    const start = performance.now();

    try {
        const result = await Promise.any([
            delay(1000, 1, true),          // reject
            delay(2000, 2),                // resolve
            delay(3000, 3),                // resolve
        ]);
        console.log("Any result:", result);
    } catch (e) {
        console.log("Any threw:", e.errors);
    }

    const end = performance.now();
    console.log("Any Execution time:", ((end - start) / 1000).toFixed(4), "seconds\n");
}

(async () => {
    console.log("=== Promise.race() ===");
    await testRace();

    console.log("=== Promise.any() ===");
    await testAny();
})();
