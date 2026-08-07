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
        return {
          html: `
            <div class="event-fc-color flex fc-event-main fc-bg-offboarding p-1 rounded-sm cursor-pointer">
              <div class="fc-daygrid-event-dot"></div>
              <div class="fc-event-title">${eventInfo.event.title}</div>
            </div>
          `,
        };
      },
    });

    calendar.render();
  }
}

export default calendarInit;
