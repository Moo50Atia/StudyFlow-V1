const canvas = document.getElementById("sim-canvas");
const ctx = canvas.getContext("2d");
const button = document.getElementById("toggle-btn");
let running = false;
let phase = 0;

function draw() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = running ? "#2563eb" : "#94a3b8";
    ctx.beginPath();
    ctx.arc(200 + Math.sin(phase) * 50, 150, 20, 0, Math.PI * 2);
    ctx.fill();
}

function toggle() {
    running = !running;
    phase += 1;
    button.textContent = running ? "Pause Simulation" : "Start Simulation";
    draw();
}

draw();
button.addEventListener("click", toggle);
button.addEventListener("keydown", (event) => {
    if (event.key === "Enter" || event.key === " ") toggle();
});
