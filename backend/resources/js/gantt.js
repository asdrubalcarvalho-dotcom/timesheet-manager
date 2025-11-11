document.addEventListener('DOMContentLoaded', async () => {
    const projectSelect = document.getElementById('project-select');
    const ganttDiv = document.getElementById('gantt');

    // Fetch projects
    const projects = await fetch('/api/projects').then(res => res.json());
    projects.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.name;
        projectSelect.appendChild(opt);
    });

    async function loadGantt(projectId) {
        const data = await fetch(`/api/tasks?project_id=${projectId}`).then(res => res.json());
        const tasks = data.map(t => ({
            id: t.id,
            name: t.name,
            start: t.start_date,
            end: t.end_date,
            progress: t.progress,
            dependencies: t.dependencies || ''
        }));
        ganttDiv.innerHTML = '';
        new Gantt('#gantt', tasks, { view_mode: 'Week' });
    }

    projectSelect.addEventListener('change', e => {
        loadGantt(e.target.value);
    });

    if (projects.length) {
        projectSelect.value = projects[0].id;
        loadGantt(projects[0].id);
    }
});
