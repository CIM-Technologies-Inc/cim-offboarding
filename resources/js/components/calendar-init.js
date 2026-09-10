import { Calendar } from "@fullcalendar/core";
import dayGridPlugin from "@fullcalendar/daygrid";
import listPlugin from "@fullcalendar/list";
import timeGridPlugin from "@fullcalendar/timegrid";
import interactionPlugin from "@fullcalendar/interaction";

export function calendarInit() {
  const calendarEl = document.querySelector("#calendar");

  if (calendarEl) {
    // Offboardees' last working day, passed from the backend via a JSON data island
    let offboardingEventsList = [];
    const dataEl = document.getElementById("offboarding-events-data");
    if (dataEl) {
      try {
        offboardingEventsList = JSON.parse(dataEl.textContent);
      } catch (e) {
        offboardingEventsList = [];
      }
    }

    const calendarHeaderToolbar = {
      left: "prev,next",
      center: "title",
      right: "dayGridMonth,timeGridWeek,timeGridDay",
    };

    // Clicking an offboardee event opens the status timeline modal
    const calendarEventClick = (info) => {
      info.jsEvent.preventDefault();
      window.dispatchEvent(
        new CustomEvent("open-offboardee-modal", {
          detail: info.event.extendedProps,
        })
      );
    };

    const calendar = new Calendar(calendarEl, {
      plugins: [dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin],
      selectable: false,
      initialView: "dayGridMonth",
      headerToolbar: calendarHeaderToolbar,
      events: offboardingEventsList,
      eventClick: calendarEventClick,
      displayEventTime: false,
      eventContent(eventInfo) {
        // Notice Period indicator — a small colored badge next to the
        // offboardee's name so Admin/HR can spot an approaching, due, or
        // already-past Notification Date at a glance without opening the
        // event. Based on the SAVED `notificationDate`/`noticePeriodStatus`
        // (see `CalendarController::index()`), never recalculated here.
        const noticePeriodStatus = eventInfo.event.extendedProps.noticePeriodStatus;
        const badgesByStatus = {
          upcoming: '<span class="ml-1 shrink-0 rounded-full bg-blue-100 px-1.5 py-0.5 text-[9px] font-semibold leading-none text-blue-700">Notice: Upcoming</span>',
          today: '<span class="ml-1 shrink-0 rounded-full bg-orange-100 px-1.5 py-0.5 text-[9px] font-semibold leading-none text-orange-700">Notice: Today</span>',
          past: '<span class="ml-1 shrink-0 rounded-full bg-gray-200 px-1.5 py-0.5 text-[9px] font-semibold leading-none text-gray-600">Notice: Past</span>',
        };
        const noticeBadge = badgesByStatus[noticePeriodStatus] ?? '';

        return {
          html: `
            <div class="event-fc-color flex items-center fc-event-main fc-bg-offboarding p-1 rounded-sm cursor-pointer">
              <div class="fc-daygrid-event-dot"></div>
              <div class="fc-event-title">${eventInfo.event.title}</div>
              ${noticeBadge}
            </div>
          `,
        };
      },
    });

    calendar.render();
  }
}

export default calendarInit;
