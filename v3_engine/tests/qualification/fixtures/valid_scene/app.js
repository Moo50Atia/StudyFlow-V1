
const canvas = document.getElementById("sim-canvas");
const ctx = canvas.getContext("2d");
const btn = document.getElementById("toggle-btn");
let running = false;
let angle = 0;

function draw() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = running ? "#2563eb" : "#94a3b8";
    ctx.beginPath();
    ctx.arc(200 + Math.sin(angle) * 50, 150, 20, 0, Math.PI * 2);
    ctx.fill();
}
draw();

function toggle() {
    running = !running;
    btn.textContent = running ? "Pause Simulation" : "Start Simulation";
    angle += 1.0;
    draw();
}

btn.addEventListener("click", toggle);
btn.addEventListener("keydown", (e) => {
    if (e.key === "Enter" || e.key === " ") {
        toggle();
    }
});
