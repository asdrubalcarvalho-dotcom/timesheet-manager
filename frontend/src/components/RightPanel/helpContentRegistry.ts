export type HelpSection = {
  titleKey: string;
  bulletKeys: string[];
};

export type HelpContent = {
  titleKey: string;
  introKey: string;
  sections: HelpSection[];
  comingSoon?: HelpSection;
};

export const helpContentRegistry: Record<string, HelpContent> = {
  timesheets: {
    titleKey: 'rightPanel.help.timesheets.title',
    introKey: 'rightPanel.help.timesheets.intro',
    sections: [
      {
        titleKey: 'rightPanel.help.timesheets.sections.calendar.title',
        bulletKeys: [
          'rightPanel.help.timesheets.sections.calendar.bullets.switchViews',
          'rightPanel.help.timesheets.sections.calendar.bullets.createEdit',
          'rightPanel.help.timesheets.sections.calendar.bullets.scope',
        ],
      },
      {
        titleKey: 'rightPanel.help.timesheets.sections.travels.title',
        bulletKeys: [
          'rightPanel.help.timesheets.sections.travels.bullets.indicators',
          'rightPanel.help.timesheets.sections.travels.bullets.listView',
        ],
      },
      {
        titleKey: 'rightPanel.help.timesheets.sections.insights.title',
        bulletKeys: [
          'rightPanel.help.timesheets.sections.insights.bullets.alerts',
          'rightPanel.help.timesheets.sections.insights.bullets.weekly',
          'rightPanel.help.timesheets.sections.insights.bullets.filters',
        ],
      },
      {
        titleKey: 'rightPanel.help.timesheets.sections.ai.title',
        bulletKeys: [
          'rightPanel.help.timesheets.sections.ai.bullets.chat',
          'rightPanel.help.timesheets.sections.ai.bullets.suggestions',
        ],
      },
    ],
    comingSoon: {
      titleKey: 'rightPanel.help.timesheets.comingSoon.title',
      bulletKeys: [
        'rightPanel.help.timesheets.comingSoon.bullets.dragRange',
        'rightPanel.help.timesheets.comingSoon.bullets.aiDrafts',
      ],
    },
  },
  timesheetsReports: {
    titleKey: 'rightPanel.help.timesheetsReports.title',
    introKey: 'rightPanel.help.timesheetsReports.intro',
    sections: [
      {
        titleKey: 'rightPanel.help.timesheetsReports.sections.filters.title',
        bulletKeys: [
          'rightPanel.help.timesheetsReports.sections.filters.bullets.dateRange',
          'rightPanel.help.timesheetsReports.sections.filters.bullets.projectTask',
          'rightPanel.help.timesheetsReports.sections.filters.bullets.technicianScope',
        ],
      },
      {
        titleKey: 'rightPanel.help.timesheetsReports.sections.analysis.title',
        bulletKeys: [
          'rightPanel.help.timesheetsReports.sections.analysis.bullets.grouping',
          'rightPanel.help.timesheetsReports.sections.analysis.bullets.totals',
          'rightPanel.help.timesheetsReports.sections.analysis.bullets.readResults',
        ],
      },
      {
        titleKey: 'rightPanel.help.timesheetsReports.sections.exports.title',
        bulletKeys: [
          'rightPanel.help.timesheetsReports.sections.exports.bullets.downloads',
          'rightPanel.help.timesheetsReports.sections.exports.bullets.share',
        ],
      },
      {
        titleKey: 'rightPanel.help.timesheetsReports.sections.ai.title',
        bulletKeys: [
          'rightPanel.help.timesheetsReports.sections.ai.bullets.questions',
          'rightPanel.help.timesheetsReports.sections.ai.bullets.summaries',
        ],
      },
    ],
  },
};

export const resolveHelpContextKey = (pathname: string): string | null => {
  if (pathname.startsWith('/timesheets/reports')) return 'timesheetsReports';
  if (pathname.startsWith('/timesheets')) return 'timesheets';
  return null;
};
