/**
 * ProofDesk Chart.js Visualizations Wrapper
 */

const Charts = {
  createProjectsDoughnut(canvasId, breakdown) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const textColor = isDark ? '#CBD5E1' : '#475569';

    return new Chart(ctx, {
      type: 'doughnut',
      data: {
        labels: ['In Progress', 'In Review', 'Planning', 'Completed'],
        datasets: [{
          data: [
            breakdown.in_progress || 0,
            breakdown.in_review || 0,
            breakdown.planning || 0,
            breakdown.completed || 0
          ],
          backgroundColor: [
            '#635BFF',
            '#F59E0B',
            '#94A3B8',
            '#16A34A'
          ],
          borderWidth: 2,
          borderColor: isDark ? '#111827' : '#FFFFFF'
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: {
            position: 'bottom',
            labels: {
              color: textColor,
              font: { family: "'Plus Jakarta Sans', sans-serif", size: 12 },
              padding: 16
            }
          }
        },
        cutout: '72%'
      }
    });
  },

  createTasksBar(canvasId, tasksBreakdown) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;

    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const textColor = isDark ? '#CBD5E1' : '#475569';
    const gridColor = isDark ? '#233044' : '#E2E8F0';

    return new Chart(ctx, {
      type: 'bar',
      data: {
        labels: ['To Do', 'In Progress', 'In Review', 'Done'],
        datasets: [{
          label: 'Tasks',
          data: [
            tasksBreakdown.todo || 0,
            tasksBreakdown.in_progress || 0,
            tasksBreakdown.in_review || 0,
            tasksBreakdown.done || 0
          ],
          backgroundColor: [
            '#94A3B8',
            '#635BFF',
            '#F59E0B',
            '#16A34A'
          ],
          borderRadius: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { color: textColor, font: { family: "'Plus Jakarta Sans', sans-serif" } }
          },
          y: {
            grid: { color: gridColor },
            ticks: {
              color: textColor,
              precision: 0,
              font: { family: "'Plus Jakarta Sans', sans-serif" }
            }
          }
        }
      }
    });
  }
};

window.Charts = Charts;
